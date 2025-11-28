import { ComponentFixture, TestBed } from '@angular/core/testing';

import { GenericPrivacyPolicyComponent } from './generic-privacy-policy.component';

describe('GenericPrivacyPolicyComponent', () => {
  let component: GenericPrivacyPolicyComponent;
  let fixture: ComponentFixture<GenericPrivacyPolicyComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
    imports: [GenericPrivacyPolicyComponent]
})
    .compileComponents();

    fixture = TestBed.createComponent(GenericPrivacyPolicyComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
