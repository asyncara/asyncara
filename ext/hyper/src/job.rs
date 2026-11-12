//! A revocable C callback capability, not a second owner of the C allocation.
//! All access occurs on the single interpreter thread. Request bodies borrow
//! callback authority; completion revokes it before C can collect the job.
use std::{cell::Cell, rc::Rc};
#[derive(Clone)]
pub(super) struct Job(Rc<Cell<Option<usize>>>);
impl Job {
    pub(super) fn new(pointer: usize) -> Self {
        Self(Rc::new(Cell::new(Some(pointer))))
    }
    pub(super) fn get(&self) -> Option<usize> {
        self.0.get()
    }
    pub(super) fn revoke(&self) {
        self.0.set(None);
    }
}
#[cfg(test)]
mod tests {
    use super::Job;
    #[test]
    fn a_retained_body_loses_callback_authority_before_c_collection() {
        let owner = Job::new(17);
        let body = owner.clone();
        assert_eq!(body.get(), Some(17));
        owner.revoke();
        assert_eq!(body.get(), None);
        drop(owner);
        assert_eq!(body.get(), None);
    }
}
